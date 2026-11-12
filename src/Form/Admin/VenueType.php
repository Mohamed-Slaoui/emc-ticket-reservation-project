<?php

namespace App\Form\Admin;

use App\DTO\VenueFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The venue form.
 */
class VenueType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['class' => 'form__control'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The name is required.'),
                    new Assert\Length(max: 150),
                ],
            ])
            ->add('address', TextType::class, [
                'label' => 'Address',
                'attr' => ['class' => 'form__control'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The address is required.'),
                    new Assert\Length(max: 255),
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
            'data_class' => VenueFormData::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'admin_venue';
    }
}
