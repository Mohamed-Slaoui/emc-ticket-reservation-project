<?php

namespace App\Form\Admin;

use App\DTO\LayoutZoneFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The zone form: a group of seats inside a configuration.
 */
class LayoutZoneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code',
                'help' => 'A short code, unique inside the configuration — for example ORCH, MEZZ or BALC.',
                'attr' => ['class' => 'form__control', 'placeholder' => 'ORCH'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The code is required.'),
                    new Assert\Length(max: 16),
                    new Assert\Regex(
                        pattern: '/^[A-Za-z0-9_-]+$/',
                        message: 'The code may only contain letters, digits, dashes and underscores.',
                    ),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Name',
                'attr' => ['class' => 'form__control'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The name is required.'),
                    new Assert\Length(max: 100),
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
            'data_class' => LayoutZoneFormData::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'admin_layout_zone';
    }
}
