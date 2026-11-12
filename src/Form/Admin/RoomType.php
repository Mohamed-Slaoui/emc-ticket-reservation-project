<?php

namespace App\Form\Admin;

use App\DTO\RoomFormData;
use App\Entity\Venue;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The room form. The venue is chosen from the list.
 */
class RoomType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('venue', EntityType::class, [
                'label' => 'Venue',
                'class' => Venue::class,
                'choice_label' => 'name',
                'placeholder' => 'Choose a venue',
                'attr' => ['class' => 'form__control'],
                'constraints' => [new Assert\NotNull(message: 'The venue is required.')],
            ])
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['class' => 'form__control'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The name is required.'),
                    new Assert\Length(max: 150),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['class' => 'form__control', 'rows' => 3],
                'constraints' => [new Assert\Length(max: 2000)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RoomFormData::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'admin_room';
    }
}
